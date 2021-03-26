import {useEffect, useState} from 'react'
import {Col, Collapse, Layout, Row, Space, Tabs, Typography} from 'antd'

import logo from './favalorologo.png'
import Tooltip, { useTooltip, TooltipPopup } from "@reach/tooltip";
import "@reach/tooltip/styles.css";



import './App.css'

const {Panel} = Collapse
const {Header, Footer, Content} = Layout
const {TabPane} = Tabs
const {Text} = Typography


const FLOORS = [1, 3, 5, 6, 7, 8, 9]

function App() {
  const [patients, setPatients] = useState([])
  const [activeFloor, setActiveFloor] = useState(7)

  useEffect(() => {
    fetch(`http://localhost/labchart/labchart.php?piso=${activeFloor}`)
      .then(response => response.json())
      .then(setPatients)
  }, [activeFloor])

  const handleChange = floor => {
    const parsedFloor = floor.split('-')
    const floorNumber = Number(parsedFloor[1])

    setActiveFloor(floorNumber)
  }

  return (
    <Layout>
      <Header style={{display: 'flex', alignItems: 'center', height: '100px'}}>
        <img alt="Fundacion Favaloro" src={logo} style={{width: '180px'}} />
      </Header>
      <Content>
        <Tabs defaultActiveKey={1} centered onChange={handleChange}>
          {FLOORS.map((floor, index) => (
            <TabPane key={`floor-${floor}`} tab={`Piso ${floor}`}>
              <Collapse defaultActiveKey={[]}>
                {patients.map(({HC, Nombre = '', Cama, Solicitud, timestamp, ...rest}) => (
                  <Panel
                    key={JSON.stringify(rest)}
                    header={`${Cama} - ${Nombre} (HC:${HC}) - ${timestamp}`}>
                    <Row gutter={[32]}>
                      {Object.keys(rest).map(title => {
						if (title.toLowerCase() === 'text_corto') {
						return null
						}
						if (title.toLowerCase() === 'text_largo') {
						return null
						}
                        if (title.toLowerCase() === 'solicitud') {
                          return (
                            <Col>
                              <Space direction="vertical">
                                <h1 style={{textTransform: 'capitalize'}}>
                                  {title}
                                </h1>
                                <Text>N°: {rest[title]}</Text>
								<Text>Fecha: {timestamp} </Text>
								<Text>  </Text> 

								<button 
								  onClick={() =>  navigator.clipboard.writeText(rest['text_corto'])}
								>
								  Copiar compacto
								</button>

								<button 
								  onClick={() =>  navigator.clipboard.writeText(rest['text_largo'])}
								>
								  Copiar completo
								</button>


                              </Space>
                            </Col>
                          )
                        }

                        return (
                          <Col>
                            <Space direction="vertical">
                              <h1>{title}</h1>
                              {Object.keys(rest[title]).map(prop => (
								<Tooltip label={`${rest[title][prop].info}`}  
								style={rest[title][prop].info === "" ? {visibility:"collapsed"} : {
									fontSize: "100%",
									background: rest[title][prop].color,
									opacity: "95%",
									color: "white",
									//border: "1px solid #000",
									borderRadius: "4px",
								}}
>
									<div>
										<Text style={{color: rest[title][prop].color}}>
											{`${rest[title][prop].nombre_estudio}: ${rest[title][prop].resultado} ${rest[title][prop].unidades}`}
										</Text>
									</div>
								</Tooltip>
                              ))}
                            </Space>
                          </Col>
                        )
                      })}
                    </Row>
                  </Panel>
				  
                ))}
              </Collapse>
			  					{<button> Click me</button>} 

            </TabPane>
          ))}
        </Tabs>
      </Content>
      <Footer>Footer</Footer>
    </Layout>
  )
}

export default App
